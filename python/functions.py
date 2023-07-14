import mysql.connector

# Database configuration
db_config = {
    'host': 'localhost',
    'user': 'myuser',
    'password': 'mypassword',
    'database': 'sharemanager',
    'auth_plugin': 'mysql_native_password'
}

def query(query_string):
    try:

        print ("query string="+query_string)

        # Connect to the database
        connection = mysql.connector.connect(**db_config)
        cursor = connection.cursor()

        # Execute the query
        cursor.execute(query_string)
        result = cursor.fetchall()

        # Close the cursor and connection
        cursor.close()
        connection.close()

        print (result)

        return result

    except mysql.connector.Error as error:
        print("Error while connecting to MySQL:", error)

def insert(insert_string):
    try:

        print ("insert string="+insert_string)

         # Connect to the database
        connection = mysql.connector.connect(**db_config)
        
        # Create a cursor
        cursor = connection.cursor()
        cursor.execute(insert_string)
        connection.commit()

         # Close the cursor and connection
        cursor.close()
        connection.close()

    except mysql.connector.Error as error:
        print("Error while connecting to MySQL:", error)
